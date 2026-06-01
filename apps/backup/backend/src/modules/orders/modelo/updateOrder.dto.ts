import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsArray, IsInt, IsNumber, IsOptional, IsString, Min, MinLength } from 'class-validator';

export class UpdateOrderDto {
  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  clientId!: number;

  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  deviceTypeId!: number;

  @ApiPropertyOptional({ example: 'Samsung' })
  @IsOptional()
  @IsString()
  deviceBrand?: string;

  @ApiPropertyOptional({ example: 'A52' })
  @IsOptional()
  @IsString()
  deviceModel?: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  devicePassword?: string;

  @ApiProperty({ example: 'ABC123' })
  @IsString()
  @MinLength(1)
  serialNumber!: string;

  @ApiProperty({ example: 'No enciende' })
  @IsString()
  @MinLength(1)
  reportedIssue!: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  clientObservations?: string;

  @ApiPropertyOptional({ example: 'medium' })
  @IsOptional()
  @IsString()
  priority?: string;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsNumber()
  estimatedCost?: number;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsNumber()
  finalCost?: number;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsNumber()
  advancePayment?: number;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  paymentMethod?: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  paymentReference?: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  technicianNotes?: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  diagnosis?: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  solution?: string;

  @ApiPropertyOptional({ example: null, nullable: true })
  @IsOptional()
  @IsString()
  estimatedCompletion?: string | null;

  @ApiPropertyOptional({ type: [Number] })
  @IsOptional()
  @IsArray()
  @IsInt({ each: true })
  accessoryIds?: number[];
}
