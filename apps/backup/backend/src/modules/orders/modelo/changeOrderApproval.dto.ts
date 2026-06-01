import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsIn, IsNumber, IsOptional, IsString } from 'class-validator';

export class ChangeOrderApprovalDto {
  @ApiProperty({ enum: ['none', 'pending', 'approved', 'rejected'] })
  @IsIn(['none', 'pending', 'approved', 'rejected'])
  approvalStatus!: 'none' | 'pending' | 'approved' | 'rejected';

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsNumber()
  approvedQuoteAmount?: number;

  @ApiPropertyOptional({ example: 'Comentario' })
  @IsOptional()
  @IsString()
  approvalComment?: string;

  @ApiPropertyOptional({ example: 'Firma' })
  @IsOptional()
  @IsString()
  approvalSignature?: string;
}

