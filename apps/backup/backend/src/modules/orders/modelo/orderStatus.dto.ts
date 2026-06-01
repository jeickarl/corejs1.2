import { ApiProperty } from '@nestjs/swagger';

export class OrderStatusDto {
  @ApiProperty({ example: 'pending' })
  slug!: string;

  @ApiProperty({ example: 'Pendiente' })
  name!: string;

  @ApiProperty({ example: '⏳' })
  emoji!: string;

  @ApiProperty({ example: '#ffc107' })
  color!: string;

  @ApiProperty({ example: 1 })
  sortOrder!: number;
}

