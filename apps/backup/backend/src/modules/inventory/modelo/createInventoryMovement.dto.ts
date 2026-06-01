import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsIn, IsInt, IsNumber, IsOptional, IsString, Min } from 'class-validator';

export class CreateInventoryMovementDto {
  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  productId!: number;

  @ApiProperty({ enum: ['in', 'out', 'adjust'] })
  @IsIn(['in', 'out', 'adjust'])
  movementType!: 'in' | 'out' | 'adjust';

  @ApiProperty({ example: 1 })
  @IsNumber()
  @Min(0.01)
  quantity!: number;

  @ApiPropertyOptional({ example: 'Ajuste manual', nullable: true })
  @IsOptional()
  @IsString()
  notes?: string | null;
}

